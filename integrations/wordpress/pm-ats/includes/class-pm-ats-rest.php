<?php
/**
 * API REST di interscambio con PortalManager (namespace pm-ats/v1). Tutte firmate HMAC (PM_ATS_Auth).
 *
 *   GET  /sync/status                     stato e versione (test di connessione)
 *   POST /sync/jobs                       {mode:"full"|"delta", items:[…], closed:[id…]} → posizioni
 *   GET  /sync/applications?limit=&after= candidature da importare (senza CV)
 *   GET  /sync/applications/{uuid}/cv     CV (binario, Content-Type del file, X-PM-SHA256)
 *   POST /sync/ack                        {items:[{uuid, ok, pm_candidate_id, pm_application_id, error}]}
 */
defined('ABSPATH') || exit;

final class PM_ATS_Rest
{
    public const NS = 'pm-ats/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        $auth = [PM_ATS_Auth::class, 'verify'];
        register_rest_route(self::NS, '/sync/status', ['methods' => 'GET', 'callback' => [self::class, 'status'], 'permission_callback' => $auth]);
        register_rest_route(self::NS, '/sync/jobs', ['methods' => 'POST', 'callback' => [self::class, 'jobs'], 'permission_callback' => $auth]);
        register_rest_route(self::NS, '/sync/applications', ['methods' => 'GET', 'callback' => [self::class, 'applications'], 'permission_callback' => $auth,
            'args' => ['limit' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100], 'after' => ['type' => 'integer', 'default' => 0, 'minimum' => 0]]]);
        register_rest_route(self::NS, '/sync/applications/(?P<uuid>[a-f0-9]{32})/cv', ['methods' => 'GET', 'callback' => [self::class, 'cv'], 'permission_callback' => $auth]);
        register_rest_route(self::NS, '/sync/ack', ['methods' => 'POST', 'callback' => [self::class, 'ack'], 'permission_callback' => $auth]);
    }

    public static function status(): WP_REST_Response
    {
        PM_ATS_Log::add('status', 200);
        return self::ok(['plugin' => PM_ATS_VERSION, 'wordpress' => get_bloginfo('version'), 'site' => home_url('/'),
            'jobs_published' => PM_ATS_Jobs::count(), 'applications' => PM_ATS_Applications::counts(),
            'list_url' => PM_ATS_Public::listUrl(), 'time' => time()]);
    }

    public static function jobs(WP_REST_Request $req): WP_REST_Response
    {
        $b = json_decode((string)$req->get_body(), true);
        if (!is_array($b) || !isset($b['items']) || !is_array($b['items'])) return self::fail('bad_payload', 400);
        if (count($b['items']) > 500) return self::fail('too_many_items', 413);
        $full = ($b['mode'] ?? 'full') === 'full';
        $r = PM_ATS_Jobs::sync($b['items'], $full, array_map('intval', (array)($b['closed'] ?? [])));
        PM_ATS_Log::add('jobs', 200, sprintf('%s: +%d ~%d =%d -%d err %d', $full ? 'full' : 'delta', $r['created'], $r['updated'], $r['unchanged'], $r['withdrawn'], count($r['errors'])));
        return self::ok($r);
    }

    public static function applications(WP_REST_Request $req): WP_REST_Response
    {
        $items = PM_ATS_Applications::pending((int)$req->get_param('limit'), (int)$req->get_param('after'));
        PM_ATS_Log::add('applications', 200, count($items) . ' righe');
        return self::ok(['items' => $items, 'next_after' => $items ? end($items)['id'] : null]);
    }

    public static function cv(WP_REST_Request $req)
    {
        $r = PM_ATS_Applications::byUuid((string)$req['uuid']);
        $p = $r ? PM_ATS_Applications::cvPath($r) : null;
        if ($p === null) { PM_ATS_Log::add('cv', 404, 'not_found'); return self::fail('cv_not_found', 404); }
        PM_ATS_Log::add('cv', 200, substr($r['uuid'], 0, 8));
        // risposta binaria: si esce dal ciclo REST dopo aver inviato il file
        add_filter('rest_pre_serve_request', static function ($served) use ($r, $p) {
            if ($served) return $served;
            nocache_headers();
            header('Content-Type: ' . $r['cv_mime']);
            header('Content-Length: ' . filesize($p));
            header('Content-Disposition: attachment; filename="cv.' . pathinfo($p, PATHINFO_EXTENSION) . '"');
            header('X-PM-SHA256: ' . $r['cv_sha256']);
            header('X-Content-Type-Options: nosniff');
            readfile($p);
            return true;
        }, 10, 1);
        return new WP_REST_Response(null, 200);
    }

    public static function ack(WP_REST_Request $req): WP_REST_Response
    {
        $b = json_decode((string)$req->get_body(), true);
        if (!is_array($b) || !isset($b['items']) || !is_array($b['items'])) return self::fail('bad_payload', 400);
        $r = PM_ATS_Applications::ack(array_slice($b['items'], 0, 200));
        PM_ATS_Log::add('ack', 200, sprintf('ok %d, errori %d, sconosciute %d', $r['synced'], $r['error'], $r['unknown']));
        return self::ok($r);
    }

    private static function ok(array $data): WP_REST_Response
    {
        $res = new WP_REST_Response(['ok' => true] + $data, 200);
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    private static function fail(string $code, int $status): WP_REST_Response
    {
        PM_ATS_Log::add('error', $status, $code);
        return new WP_REST_Response(['ok' => false, 'error' => $code], $status);
    }
}
