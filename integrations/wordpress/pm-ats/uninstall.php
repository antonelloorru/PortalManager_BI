<?php
/**
 * Disinstallazione: rimuove i dati solo se l'opzione «Elimina … alla disinstallazione» è attiva.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

$s = get_option('pm_ats_settings', []);
if (empty($s['remove_on_uninstall'])) return;

global $wpdb;
$rel = (string)get_option('pm_ats_private_dir', '');
if ($rel !== '' && preg_match('/^pm-ats-private-[a-f0-9]{16}$/', $rel)) {
    $dir = trailingslashit(wp_upload_dir(null, false)['basedir']) . $rel;
    foreach ((array)glob($dir . '/{,.}*', GLOB_BRACE) as $f) if (is_file($f)) @unlink($f);
    @rmdir($dir);
}
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}pm_ats_applications");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}pm_ats_log");
foreach ((array)get_posts(['post_type' => 'pm_job', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $id) wp_delete_post((int)$id, true);
foreach (['pm_ats_settings', 'pm_ats_secret_enc', 'pm_ats_db_version', 'pm_ats_private_dir', 'pm_ats_last_contact', 'pm_ats_last_jobs_sync', 'pm_ats_flush_rewrite'] as $o) delete_option($o);
$role = get_role('administrator');
if ($role) $role->remove_cap('pm_ats_view');
