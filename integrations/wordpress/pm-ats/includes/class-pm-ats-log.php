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

    /** v1.1.1 — ultimo rifiuto di autenticazione dopo l'ultimo contatto riuscito (null se nessuno). */
    public static function lastAuthFailure(): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row('SELECT created_at, http_status, detail, ip FROM ' . self::table() . " WHERE action = 'auth' ORDER BY id DESC LIMIT 1", ARRAY_A);
        if (!$r) return null;
        $at = (int)strtotime($r['created_at'] . ' UTC');
        $ok = get_option('pm_ats_last_contact');
        if (is_array($ok) && (int)($ok['at'] ?? 0) > $at) return null;
        return ['at' => $at, 'status' => (int)$r['http_status'], 'detail' => $r['detail'] . ' (IP ' . $r['ip'] . ')'];
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
