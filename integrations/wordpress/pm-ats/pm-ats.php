<?php
/**
 * Plugin Name:       PortalManager ATS – Posizioni aperte & Candidature
 * Description:       Pubblica le posizioni aperte di PortalManager e raccoglie le candidature (CV) dal sito. La sincronizzazione è avviata da PortalManager: invio delle posizioni e prelievo delle candidature tramite API REST firmate HMAC.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Tested up to:      6.8
 * Requires PHP:      8.0
 * Author:            PortalManager
 * Update URI:        false
 * License:           Proprietary
 * Text Domain:       pm-ats
 *
 * Direzione dei dati:
 *   PortalManager → WP  POST /wp-json/pm-ats/v1/sync/jobs              (posizioni, avviato da PM)
 *   PortalManager ← WP  GET  /wp-json/pm-ats/v1/sync/applications      (candidature, prelevate da PM)
 *                       GET  /wp-json/pm-ats/v1/sync/applications/{id}/cv
 *                       POST /wp-json/pm-ats/v1/sync/ack                (esito importazione)
 *                       GET  /wp-json/pm-ats/v1/sync/status             (test di connessione)
 *   Nessuna chiamata parte da WordPress verso PortalManager.
 *
 * Versioning (semver MAJOR.MINOR.PATCH, v1.1.0; 1.1.1 fix doppia verifica HMAC + diagnostica):
 *   PM_ATS_VERSION           versione del plugin (header «Version», readme «Stable tag», asset ?ver=, template @version)
 *   PM_ATS_API_VERSION       versione del protocollo REST pm-ats/vN (cambia solo con modifiche incompatibili)
 *   PM_ATS_DB_VERSION        schema delle tabelle; PM_ATS_SETTINGS_VERSION schema delle impostazioni
 *   PM_ATS_TEMPLATE_VERSION  versione dei template sovrascrivibili (avviso se il tema ne ha di più vecchi)
 *   PM_ATS_MIN_PM            versione minima di PortalManager per tutte le funzioni
 *   Aggiornamenti: PM_ATS_Upgrade::maybe() a ogni caricamento (migrazioni idempotenti + storico versioni).
 *
 * Frontend: shortcode [pm_ats_jobs] (elenco + filtri + candidatura spontanea), [pm_ats_apply], scheda posizione
 * con modulo di candidatura e CV; template sovrascrivibili in <tema>/pm-ats/.
 */
defined('ABSPATH') || exit;

define('PM_ATS_VERSION', '1.1.1');
define('PM_ATS_API_VERSION', '1');
define('PM_ATS_DB_VERSION', '1');
define('PM_ATS_SETTINGS_VERSION', '2');
define('PM_ATS_TEMPLATE_VERSION', '1.1.0');
define('PM_ATS_MIN_PM', '1.10.14');
define('PM_ATS_FILE', __FILE__);
define('PM_ATS_DIR', plugin_dir_path(__FILE__));
define('PM_ATS_URL', plugin_dir_url(__FILE__));

require_once PM_ATS_DIR . 'includes/class-pm-ats-settings.php';
require_once PM_ATS_DIR . 'includes/class-pm-ats-log.php';
require_once PM_ATS_DIR . 'includes/class-pm-ats-auth.php';
require_once PM_ATS_DIR . 'includes/class-pm-ats-jobs.php';
require_once PM_ATS_DIR . 'includes/class-pm-ats-applications.php';
require_once PM_ATS_DIR . 'includes/class-pm-ats-rest.php';
require_once PM_ATS_DIR . 'includes/class-pm-ats-public.php';
require_once PM_ATS_DIR . 'includes/class-pm-ats-privacy.php';
require_once PM_ATS_DIR . 'includes/class-pm-ats-upgrade.php';
if (is_admin()) {
    require_once PM_ATS_DIR . 'includes/class-pm-ats-admin.php';
    require_once PM_ATS_DIR . 'includes/class-pm-ats-setup.php';
}

register_activation_hook(__FILE__, ['PM_ATS_Applications', 'activate']);
register_activation_hook(__FILE__, ['PM_ATS_Upgrade', 'activate']);   // v1.1.0 — configurazione guidata al primo avvio
register_deactivation_hook(__FILE__, ['PM_ATS_Applications', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('pm-ats', false, dirname(plugin_basename(PM_ATS_FILE)) . '/languages');
    if (get_option('pm_ats_db_version') !== PM_ATS_DB_VERSION) PM_ATS_Applications::install();
    PM_ATS_Upgrade::maybe();
    PM_ATS_Jobs::init();
    PM_ATS_Rest::init();
    PM_ATS_Public::init();
    PM_ATS_Privacy::init();
    PM_ATS_Applications::init();
    if (is_admin()) { PM_ATS_Admin::init(); PM_ATS_Setup::init(); }
});
