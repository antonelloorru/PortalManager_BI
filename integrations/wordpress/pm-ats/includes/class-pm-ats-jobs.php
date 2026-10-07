<?php
/**
 * Posizioni aperte: custom post type `pm_job`, alimentato SOLO da PortalManager (POST /sync/jobs).
 * In WordPress le posizioni non si creano a mano: le modifiche si fanno in PortalManager e arrivano col push.
 *
 * Meta: _pm_id (id job_positions), _pm_hash (impronta del contenuto ricevuto), _pm_data (array normalizzato),
 *       _pm_web_status (v1.2.0): publish = visibile | draft = bozza (non visibile, anteprima) | withdrawn = ritirata.
 */
defined('ABSPATH') || exit;

final class PM_ATS_Jobs
{
    public const CPT = 'pm_job';

    /**
     * v1.3.0 — STRUTTURA VINCOLANTE della Job Description (ordine obbligatorio, indipendente dal layout e dal tema):
     *   1 Chi siamo · 2 Informazioni sull'offerta · 3 Competenze · 4 Costituisce titolo preferenziale · 5 Cosa offriamo
     * chiave => [titolo, [campo PortalManager => sottotitolo ('' = nessuno)]]. La nota di pari opportunità (gender_disclaimer)
     * chiude la scheda senza titolo. Il campo «description» di PortalManager (note interne) non è mai pubblicato.
     * Unica sorgente per scheda, elenco a fisarmonica, anteprima, estratto e dati strutturati: i template usano sections().
     */
    public const STRUCTURE = [
        'chi-siamo'     => ['Chi siamo', ['presentation_text' => '']],
        'offerta'       => ['Informazioni sull\'offerta', ['offer_info' => '']],
        'competenze'    => ['Competenze', ['required_skills' => 'Requisiti', 'hard_skills' => 'Competenze tecniche', 'soft_skills' => 'Competenze trasversali']],
        'preferenziale' => ['Costituisce titolo preferenziale', ['nice_to_have' => '']],
        'offriamo'      => ['Cosa offriamo', ['we_offer' => '', 'benefits' => 'Benefit']],
    ];
    public const CLOSING = 'gender_disclaimer';

    /**
     * Sezioni testuali (compatibilità con i template 1.0/1.1 sovrascritti dal tema): stesso ordine della STRUCTURE,
     * campo => titolo. Non contiene più «description».
     */
    public const SECTIONS = [
        'presentation_text' => 'Chi siamo',
        'offer_info'        => 'Informazioni sull\'offerta',
        'required_skills'   => 'Competenze',
        'hard_skills'       => 'Competenze tecniche',
        'soft_skills'       => 'Competenze trasversali',
        'nice_to_have'      => 'Costituisce titolo preferenziale',
        'we_offer'          => 'Cosa offriamo',
        'benefits'          => 'Benefit',
        'gender_disclaimer' => '',
    ];

    /**
     * Sezioni presenti della posizione, nell'ordine vincolante.
     * @return array<int,array{key:string,n:int,title:string,parts:array<int,array{field:string,subtitle:string,text:string}>}>
     */
    public static function sections(array $job): array
    {
        $out = []; $n = 0;
        foreach (self::STRUCTURE as $key => [$title, $fields]) {
            $n++;
            $parts = [];
            foreach ($fields as $f => $sub) if (trim((string)($job[$f] ?? '')) !== '') $parts[] = ['field' => $f, 'subtitle' => $sub, 'text' => (string)$job[$f]];
            if (!$parts) continue;
            // un solo blocco: nessun sottotitolo (il titolo della sezione basta)
            if (count($parts) === 1) $parts[0]['subtitle'] = '';
            $out[] = ['key' => $key, 'n' => $n, 'title' => $title, 'parts' => $parts];
        }
        return $out;
    }

    /** HTML delle sezioni nell'ordine vincolante (titolo $h, sottotitoli $h+1) + nota di chiusura. */
    public static function sectionsHtml(array $job, string $h = 'h2', string $class = 'pm-ats-section'): string
    {
        $sub = 'h' . min(6, (int)substr($h, 1) + 1);
        $o = '';
        foreach (self::sections($job) as $s) {
            $o .= '<section class="' . esc_attr($class) . ' ' . esc_attr($class) . '-' . esc_attr($s['key']) . '" data-pm-ats-section="' . (int)$s['n'] . '">'
                . '<' . $h . ' class="' . esc_attr($class) . '-title">' . esc_html(__($s['title'], 'pm-ats')) . '</' . $h . '>';
            foreach ($s['parts'] as $p)
                $o .= ($p['subtitle'] !== '' ? '<' . $sub . ' class="' . esc_attr($class) . '-subtitle">' . esc_html(__($p['subtitle'], 'pm-ats')) . '</' . $sub . '>' : '') . self::format($p['text']);
            $o .= '</section>';
        }
        if (trim((string)($job[self::CLOSING] ?? '')) !== '') $o .= '<div class="' . esc_attr($class) . ' ' . esc_attr($class) . '-closing">' . self::format((string)$job[self::CLOSING]) . '</div>';
        return $o;
    }
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
            // v1.2.0 — stato di pubblicazione deciso in PortalManager per la singola posizione
            $want = ($raw['web_status'] ?? 'publish') === 'draft' ? 'draft' : 'publish';
            $hash = hash('sha256', wp_json_encode($d));
            $pid = self::postIdFor($d['id']);
            if ($pid && get_post_meta($pid, '_pm_hash', true) === $hash && get_post_status($pid) === $want && get_post_meta($pid, '_pm_web_status', true) === $want) {
                $out['unchanged']++; $out['map'][] = ['id' => $d['id'], 'post_id' => $pid, 'url' => get_permalink($pid), 'status' => $want]; continue;
            }
            // estratto: il testo specifico della posizione prima della presentazione aziendale (uguale per tutte)
            $plain = '';
            foreach (['offer_info', 'required_skills', 'hard_skills', 'we_offer', 'presentation_text'] as $k) if ($d[$k] !== '') { $plain = $d[$k]; break; }
            $plain = trim(preg_replace('/^\s*(?:[-*•·▪◦]|\d+[.)])\s+/mu', '', wp_strip_all_tags($plain)));
            $post = [
                'post_type' => self::CPT, 'post_status' => $want, 'post_title' => $d['title'],
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
            update_post_meta($pid, '_pm_web_status', $want);
            foreach (self::META_FIELDS as $k) update_post_meta($pid, '_pm_' . $k, wp_slash((string)$d[$k]));
            $out['map'][] = ['id' => $d['id'], 'post_id' => $pid, 'url' => get_permalink($pid), 'status' => $want];
        }
        $toClose = array_map('intval', $closed);
        if ($full) {
            foreach (self::allPmIds() as $pmId => $pid) if (!in_array($pmId, $seen, true)) $toClose[] = $pmId;
        }
        foreach (array_unique($toClose) as $pmId) {
            $pid = self::postIdFor($pmId);
            if (!$pid) continue;
            if (get_post_status($pid) === 'publish') { wp_update_post(['ID' => $pid, 'post_status' => 'draft']); $out['withdrawn']++; }
            update_post_meta($pid, '_pm_web_status', 'withdrawn');
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
        $d['description'] = '';   // v1.3.0 — note interne di PortalManager: mai pubblicate né conservate
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

    /** @return array<int,int> pm_id => post_id per le posizioni pubblicate o in bozza (v1.2.0: le bozze assenti diventano ritirate) */
    public static function allPmIds(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_value pm, p.ID pid FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_pm_id'
              LEFT JOIN {$wpdb->postmeta} w ON w.post_id = p.ID AND w.meta_key = '_pm_web_status'
              WHERE p.post_type = %s AND (p.post_status = 'publish' OR (p.post_status = 'draft' AND w.meta_value = 'draft'))", self::CPT), ARRAY_A);
        $o = []; foreach ((array)$rows as $r) $o[(int)$r['pm']] = (int)$r['pid'];
        return $o;
    }

    /* ── anteprima (v1.2.0) ───────────────────────────────────────────── */

    public const PREVIEW_TTL = 1800;

    /**
     * Anteprima di una posizione inviata da PortalManager (anche mai pubblicata o in bozza): i dati sono salvati in un
     * transient con un token casuale; l'URL pubblico ?pm_ats_preview=<token> vale PREVIEW_TTL secondi, non è indicizzato
     * e non crea né modifica alcun contenuto.
     * @return array{token:string,url:string,expires:int,post_url:?string,post_status:?string}|null
     */
    public static function preview(array $raw): ?array
    {
        $d = self::normalize($raw);
        if ($d === null) return null;
        $tok = bin2hex(random_bytes(24));
        set_transient('pm_ats_pv_' . $tok, ['data' => $d, 'web_status' => (string)($raw['web_status'] ?? '')], self::PREVIEW_TTL);
        $pid = self::postIdFor($d['id']);
        return ['token' => $tok, 'url' => add_query_arg('pm_ats_preview', $tok, home_url('/')), 'expires' => time() + self::PREVIEW_TTL,
                'post_url' => $pid ? (string)get_permalink($pid) : null, 'post_status' => $pid ? (string)get_post_status($pid) : null,
                'web_status' => $pid ? (string)get_post_meta($pid, '_pm_web_status', true) : null];
    }

    public static function previewData(string $tok): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
        $v = get_transient('pm_ats_pv_' . $tok);
        return is_array($v) && isset($v['data']) ? $v : null;
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
        foreach (self::sections($d) as $s) { $desc .= '<h3>' . esc_html($s['title']) . '</h3>'; foreach ($s['parts'] as $p) $desc .= ($p['subtitle'] !== '' ? '<h4>' . esc_html($p['subtitle']) . '</h4>' : '') . self::format($p['text']); }
        if (($d[self::CLOSING] ?? '') !== '') $desc .= self::format($d[self::CLOSING]);
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
