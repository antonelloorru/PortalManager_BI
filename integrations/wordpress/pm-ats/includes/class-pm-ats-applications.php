<?php
/**
 * Candidature raccolte dal sito, in attesa del prelievo da parte di PortalManager.
 *
 * Tabella {prefix}pm_ats_applications. CV in una cartella privata sotto uploads (nome casuale,
 * accesso web negato da .htaccess/web.config, file con nome casuale). Ciclo di vita:
 *   pending  → PortalManager la preleva (GET /sync/applications, GET /sync/applications/{uuid}/cv)
 *   synced   → PortalManager conferma (POST /sync/ack): CV e dati non necessari eliminati (purge_after_ack)
 *   error    → PortalManager ha rifiutato l'import (motivo in last_error); resta prelevabile
 * Pulizia giornaliera (cron): synced oltre retention_synced giorni, pending/error oltre retention_pending.
 */
defined('ABSPATH') || exit;

final class PM_ATS_Applications
{
    /** Estensione => MIME canonico + MIME accettati da finfo. */
    public const MIME = [
        'pdf'  => ['application/pdf', ['application/pdf']],
        'doc'  => ['application/msword', ['application/msword', 'application/cdfv2', 'application/x-ole-storage', 'application/vnd.ms-office']],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                   ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
    ];
    public const CRON = 'pm_ats_daily';

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'pm_ats_applications'; }

    public static function init(): void
    {
        add_action(self::CRON, [self::class, 'purge']);
    }

    public static function activate(): void
    {
        self::install();
        PM_ATS_Jobs::register();
        flush_rewrite_rules(false);
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 3600, 'daily', self::CRON);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON);
        flush_rewrite_rules(false);
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $t = self::table(); $l = PM_ATS_Log::table();
        dbDelta("CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(32) NOT NULL,
  job_post_id bigint(20) unsigned DEFAULT NULL,
  pm_position_id int(11) DEFAULT NULL,
  position_title varchar(200) NOT NULL DEFAULT '',
  first_name varchar(100) NOT NULL,
  last_name varchar(100) NOT NULL,
  email varchar(150) NOT NULL,
  phone varchar(30) NOT NULL DEFAULT '',
  city varchar(120) NOT NULL DEFAULT '',
  linkedin_url varchar(255) NOT NULL DEFAULT '',
  availability varchar(50) NOT NULL DEFAULT '',
  salary_expectation varchar(30) NOT NULL DEFAULT '',
  cover_letter text NULL,
  consent_privacy tinyint(1) NOT NULL DEFAULT 0,
  privacy_version varchar(40) NOT NULL DEFAULT '',
  consent_marketing tinyint(1) NOT NULL DEFAULT 0,
  consent_at datetime NOT NULL,
  cv_file varchar(80) NOT NULL DEFAULT '',
  cv_name varchar(190) NOT NULL DEFAULT '',
  cv_mime varchar(120) NOT NULL DEFAULT '',
  cv_size int(10) unsigned NOT NULL DEFAULT 0,
  cv_sha256 char(64) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  last_error varchar(255) NOT NULL DEFAULT '',
  pm_candidate_id int(11) DEFAULT NULL,
  pm_application_id int(11) DEFAULT NULL,
  created_at datetime NOT NULL,
  fetched_at datetime DEFAULT NULL,
  synced_at datetime DEFAULT NULL,
  purged_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_uuid (uuid),
  KEY idx_status (status,id),
  KEY idx_email (email),
  KEY idx_created (created_at)
) $c;");
        dbDelta("CREATE TABLE $l (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  action varchar(40) NOT NULL,
  http_status smallint(5) unsigned NOT NULL DEFAULT 200,
  ip varchar(45) NOT NULL DEFAULT '',
  detail varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY idx_created (created_at)
) $c;");
        self::privateDir();
        update_option('pm_ats_db_version', PM_ATS_DB_VERSION);
    }

    /** Cartella privata dei CV (creata e protetta al primo uso). */
    public static function privateDir(): string
    {
        $up = wp_upload_dir(null, false);
        $rel = (string)get_option('pm_ats_private_dir', '');
        if ($rel === '') { $rel = 'pm-ats-private-' . bin2hex(random_bytes(8)); update_option('pm_ats_private_dir', $rel, false); }
        $dir = trailingslashit($up['basedir']) . $rel;
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $guard = [
            '.htaccess'  => "# pm-ats: accesso web negato\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\nOptions -Indexes\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.php'  => "<?php\nhttp_response_code(403);\n",
        ];
        foreach ($guard as $f => $body) if (!is_file("$dir/$f")) @file_put_contents("$dir/$f", $body);
        return $dir;
    }

    /* ── invio dal sito ──────────────────────────────────────────────── */

    /**
     * Valida e registra una candidatura.
     * @return array{ok:bool, uuid?:string, error?:string, field?:string}
     */
    public static function submit(array $in, ?array $file): array
    {
        $s = PM_ATS_Settings::all();
        $t = static fn(string $k, int $max) => mb_substr(trim(sanitize_text_field(wp_unslash((string)($in[$k] ?? '')))), 0, $max);
        $d = [
            'first_name' => $t('first_name', 100), 'last_name' => $t('last_name', 100),
            'email' => strtolower(sanitize_email(wp_unslash((string)($in['email'] ?? '')))),
            'phone' => $t('phone', 30), 'city' => $t('city', 120),
            'linkedin_url' => esc_url_raw(trim(wp_unslash((string)($in['linkedin_url'] ?? '')))),
            'availability' => $t('availability', 50), 'salary_expectation' => $t('salary_expectation', 30),
            'cover_letter' => mb_substr(trim(sanitize_textarea_field(wp_unslash((string)($in['cover_letter'] ?? '')))), 0, 5000),
            'consent_privacy' => empty($in['consent_privacy']) ? 0 : 1,
            'consent_marketing' => empty($in['consent_marketing']) ? 0 : 1,
        ];
        foreach (['first_name' => 'missing_first_name', 'last_name' => 'missing_last_name'] as $k => $e) if ($d[$k] === '') return self::e($e, $k);
        if (!is_email($d['email'])) return self::e('invalid_email', 'email');
        if (!empty($s['phone_required']) && $d['phone'] === '') return self::e('missing_phone', 'phone');
        if ($d['phone'] !== '' && !preg_match('/^[+0-9 ().\/-]{6,30}$/', $d['phone'])) return self::e('invalid_phone', 'phone');
        if ($d['linkedin_url'] !== '' && !preg_match('#^https://([a-z]{2,3}\.)?linkedin\.com/#i', $d['linkedin_url'])) return self::e('invalid_linkedin', 'linkedin_url');
        if (!$d['consent_privacy']) return self::e('privacy_required', 'consent_privacy');

        // posizione: un pm_job pubblicato, oppure candidatura spontanea se ammessa
        $jobId = (int)($in['job_id'] ?? 0); $pmPos = null; $title = '';
        if ($jobId > 0) {
            $p = get_post($jobId);
            if (!$p || $p->post_type !== PM_ATS_Jobs::CPT || $p->post_status !== 'publish') return self::e('position_closed');
            $pmPos = (int)get_post_meta($jobId, '_pm_id', true) ?: null; $title = $p->post_title;
        } elseif (empty($s['allow_spontaneous'])) {
            return self::e('position_closed');
        } else { $jobId = 0; $title = __('Candidatura spontanea', 'pm-ats'); }

        // anti-abuso: limite per IP al giorno e candidatura già presente
        $ip = PM_ATS_Auth::clientIp();
        $rk = 'pm_ats_r_' . md5($ip . '|' . gmdate('Ymd'));
        if ((int)get_transient($rk) >= (int)$s['rate_per_day']) return self::e('rate_limited');
        global $wpdb;
        $dup = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table()
            . ' WHERE email = %s AND COALESCE(job_post_id,0) = %d AND created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)', $d['email'], $jobId));
        if ($dup) return self::e('already_applied');

        // CV
        $cv = self::validateCv($file, $s);
        if (isset($cv['error'])) return self::e($cv['error'], 'cv');
        $dir = self::privateDir();
        $stored = bin2hex(random_bytes(16)) . '.' . $cv['ext'];
        if (!@move_uploaded_file($file['tmp_name'], "$dir/$stored")) return self::e('upload_failed', 'cv');
        @chmod("$dir/$stored", 0640);

        $uuid = bin2hex(random_bytes(16));
        $ok = $wpdb->insert(self::table(), $d + [
            'uuid' => $uuid, 'job_post_id' => $jobId ?: null, 'pm_position_id' => $pmPos, 'position_title' => mb_substr($title, 0, 200),
            'privacy_version' => (string)$s['privacy_version'], 'consent_at' => current_time('mysql', true),
            'cv_file' => $stored, 'cv_name' => mb_substr(sanitize_file_name((string)$file['name']), 0, 190), 'cv_mime' => $cv['mime'],
            'cv_size' => (int)$cv['size'], 'cv_sha256' => hash_file('sha256', "$dir/$stored"),
            'ip' => $ip, 'user_agent' => mb_substr(sanitize_text_field((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
            'status' => 'pending', 'created_at' => current_time('mysql', true),
        ]);
        if (!$ok) { @unlink("$dir/$stored"); return self::e('save_failed'); }
        set_transient($rk, (int)get_transient($rk) + 1, DAY_IN_SECONDS);
        self::notify($d, $title, $uuid);
        do_action('pm_ats_application_received', $uuid, $jobId);
        return ['ok' => true, 'uuid' => $uuid];
    }

    /** @return array{ext?:string,mime?:string,size?:int,error?:string} */
    public static function validateCv(?array $file, array $s): array
    {
        if (!$file || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return ['error' => 'cv_missing'];
        if ((int)$file['error'] === UPLOAD_ERR_INI_SIZE || (int)$file['error'] === UPLOAD_ERR_FORM_SIZE) return ['error' => 'cv_too_large'];
        if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) return ['error' => 'upload_failed'];
        $size = (int)filesize($file['tmp_name']);
        if ($size <= 0 || $size > (int)$s['cv_max_mb'] * 1048576) return ['error' => 'cv_too_large'];
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $allowed = array_map('trim', explode(',', (string)$s['cv_types']));
        if (!isset(self::MIME[$ext]) || !in_array($ext, $allowed, true)) return ['error' => 'cv_bad_type'];
        $real = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']));
        if (!in_array($real, self::MIME[$ext][1], true)) return ['error' => 'cv_bad_type'];
        $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 8);
        $magic = ['pdf' => "%PDF-", 'docx' => "PK\x03\x04", 'doc' => "\xD0\xCF\x11\xE0"];
        if (!str_starts_with($head, $magic[$ext])) return ['error' => 'cv_bad_type'];
        return ['ext' => $ext, 'mime' => self::MIME[$ext][0], 'size' => $size];
    }

    private static function e(string $code, string $field = ''): array { return ['ok' => false, 'error' => $code, 'field' => $field]; }

    private static function notify(array $d, string $title, string $uuid): void
    {
        $s = PM_ATS_Settings::all(); $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        if ($s['notify_email'] !== '' && is_email($s['notify_email'])) {
            wp_mail($s['notify_email'], sprintf('[%s] %s — %s', $site, __('Nuova candidatura', 'pm-ats'), $title),
                sprintf("%s: %s\n%s: %s %s\n%s: %s\n\n%s\n%s",
                    __('Posizione', 'pm-ats'), $title, __('Candidato', 'pm-ats'), $d['first_name'], $d['last_name'],
                    __('Riferimento', 'pm-ats'), substr($uuid, 0, 8),
                    __('Il CV sarà importato in PortalManager alla prossima sincronizzazione.', 'pm-ats'),
                    admin_url('admin.php?page=pm-ats')));
        }
        if (!empty($s['confirm_candidate'])) {
            wp_mail($d['email'], sprintf('%s — %s', $site, __('abbiamo ricevuto la tua candidatura', 'pm-ats')),
                sprintf(__("Gentile %s,\n\ngrazie per l'interesse: abbiamo ricevuto la tua candidatura per «%s».\nIl nostro team HR la valuterà e ti contatterà in caso di riscontro positivo.\n\nRiferimento: %s\n\n%s", 'pm-ats'),
                    $d['first_name'], $title, strtoupper(substr($uuid, 0, 8)), $site));
        }
    }

    /* ── prelievo da PortalManager ───────────────────────────────────── */

    public static function pending(int $limit, int $afterId = 0): array
    {
        global $wpdb;
        $rows = (array)$wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table()
            . " WHERE status IN ('pending','error') AND purged_at IS NULL AND id > %d ORDER BY id LIMIT %d", $afterId, $limit), ARRAY_A);
        if ($rows) {
            $ids = implode(',', array_map('intval', array_column($rows, 'id')));
            $wpdb->query('UPDATE ' . self::table() . " SET fetched_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE id IN ($ids)");
        }
        return array_map([self::class, 'export'], $rows);
    }

    /** Rappresentazione inviata a PortalManager (nessun percorso di file). */
    public static function export(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'uuid' => $r['uuid'], 'status' => $r['status'], 'attempts' => (int)$r['attempts'],
            'pm_position_id' => $r['pm_position_id'] !== null ? (int)$r['pm_position_id'] : null,
            'position_title' => $r['position_title'], 'spontaneous' => $r['job_post_id'] === null,
            'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'email' => $r['email'],
            'phone' => $r['phone'], 'city' => $r['city'], 'linkedin_url' => $r['linkedin_url'],
            'availability' => $r['availability'], 'salary_expectation' => $r['salary_expectation'],
            'cover_letter' => (string)$r['cover_letter'],
            'consent_privacy' => (int)$r['consent_privacy'], 'privacy_version' => $r['privacy_version'],
            'consent_marketing' => (int)$r['consent_marketing'], 'consent_at' => $r['consent_at'] . 'Z',
            'ip' => $r['ip'], 'user_agent' => $r['user_agent'], 'created_at' => $r['created_at'] . 'Z',
            'cv' => $r['cv_file'] !== '' ? ['name' => $r['cv_name'], 'mime' => $r['cv_mime'], 'size' => (int)$r['cv_size'], 'sha256' => $r['cv_sha256']] : null,
        ];
    }

    public static function byUuid(string $uuid): ?array
    {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{32}$/', $uuid)) return null;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE uuid = %s', $uuid), ARRAY_A);
        return $r ?: null;
    }

    public static function cvPath(array $r): ?string
    {
        if ($r['cv_file'] === '' || !preg_match('/^[a-f0-9]{32}\.(pdf|docx?)$/', $r['cv_file'])) return null;
        $p = self::privateDir() . '/' . $r['cv_file'];
        return is_file($p) ? $p : null;
    }

    /**
     * Esito dell'import in PortalManager.
     * @param array $items [{uuid, ok:bool, pm_candidate_id?, pm_application_id?, error?}]
     */
    public static function ack(array $items): array
    {
        global $wpdb;
        $out = ['synced' => 0, 'error' => 0, 'unknown' => 0];
        $purge = (bool)PM_ATS_Settings::get('purge_after_ack');
        foreach ($items as $it) {
            $r = self::byUuid((string)($it['uuid'] ?? ''));
            if (!$r) { $out['unknown']++; continue; }
            if (!empty($it['ok'])) {
                $wpdb->update(self::table(), [
                    'status' => 'synced', 'synced_at' => current_time('mysql', true), 'last_error' => '',
                    'pm_candidate_id' => isset($it['pm_candidate_id']) ? (int)$it['pm_candidate_id'] : null,
                    'pm_application_id' => isset($it['pm_application_id']) ? (int)$it['pm_application_id'] : null,
                ], ['id' => (int)$r['id']]);
                if ($purge) self::minimize((int)$r['id']);
                $out['synced']++;
            } else {
                $wpdb->update(self::table(), ['status' => 'error', 'last_error' => mb_substr(sanitize_text_field((string)($it['error'] ?? 'import_failed')), 0, 255)], ['id' => (int)$r['id']]);
                $out['error']++;
            }
        }
        return $out;
    }

    /** Minimizzazione: elimina CV e dati non più necessari una volta in PortalManager. */
    public static function minimize(int $id): void
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        if (!$r) return;
        if (($p = self::cvPath($r)) !== null) wp_delete_file($p);
        $wpdb->update(self::table(), ['cv_file' => '', 'cover_letter' => null, 'phone' => '', 'city' => '', 'linkedin_url' => '',
            'availability' => '', 'salary_expectation' => '', 'ip' => '', 'user_agent' => '', 'purged_at' => current_time('mysql', true)], ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        if (!$r) return;
        if (($p = self::cvPath($r)) !== null) wp_delete_file($p);
        $wpdb->delete(self::table(), ['id' => $id]);
    }

    /** Cron giornaliero: conservazione e registro. */
    public static function purge(): void
    {
        global $wpdb;
        $s = PM_ATS_Settings::all();
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::table()
            . " WHERE (status = 'synced' AND synced_at < (UTC_TIMESTAMP() - INTERVAL %d DAY))
                  OR (status IN ('pending','error') AND created_at < (UTC_TIMESTAMP() - INTERVAL %d DAY))",
            (int)$s['retention_synced'], (int)$s['retention_pending']));
        foreach ((array)$ids as $id) self::delete((int)$id);
        PM_ATS_Log::purge(90);
    }

    public static function counts(): array
    {
        global $wpdb;
        $o = ['pending' => 0, 'synced' => 0, 'error' => 0];
        foreach ((array)$wpdb->get_results('SELECT status, COUNT(*) n FROM ' . self::table() . ' GROUP BY status', ARRAY_A) as $r) $o[$r['status']] = (int)$r['n'];
        return $o;
    }

    public static function list(string $status = '', int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $w = $status !== '' ? $wpdb->prepare('WHERE status = %s', $status) : '';
        return (array)$wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " $w ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
    }
}
