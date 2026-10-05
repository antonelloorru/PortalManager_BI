<?php
/**
 * Registro delle interazioni con PortalManager (tabella {prefix}pm_ats_log). Nessun dato personale.
 */
defined('ABSPATH') || exit;

final class PM_ATS_Log
{
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'pm_ats_log'; }

    public static function add(string $action, int $status, string $detail = ''): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'created_at' => current_time('mysql', true), 'action' => substr($action, 0, 40), 'http_status' => $status,
            'ip' => substr(PM_ATS_Auth::clientIp(), 0, 45), 'detail' => mb_substr($detail, 0, 500),
        ], ['%s', '%s', '%d', '%s', '%s']);
    }

    public static function recent(int $limit = 100): array
    {
        global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A);
    }

    public static function purge(int $days = 90): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_at < (UTC_TIMESTAMP() - INTERVAL %d DAY)', $days));
    }
}
