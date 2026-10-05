<?php
/**
 * GDPR: esportazione e cancellazione dei dati personali (Strumenti › Esporta/Cancella dati personali)
 * e testo suggerito per l'informativa privacy del sito.
 */
defined('ABSPATH') || exit;

final class PM_ATS_Privacy
{
    public static function init(): void
    {
        add_filter('wp_privacy_personal_data_exporters', static function (array $e): array {
            $e['pm-ats'] = ['exporter_friendly_name' => __('Candidature (Lavora con noi)', 'pm-ats'), 'callback' => [self::class, 'export']];
            return $e;
        });
        add_filter('wp_privacy_personal_data_erasers', static function (array $e): array {
            $e['pm-ats'] = ['eraser_friendly_name' => __('Candidature (Lavora con noi)', 'pm-ats'), 'callback' => [self::class, 'erase']];
            return $e;
        });
        add_action('admin_init', static function (): void {
            if (function_exists('wp_add_privacy_policy_content')) {
                wp_add_privacy_policy_content(__('Lavora con noi (PortalManager ATS)', 'pm-ats'), wp_kses_post(__(
                    '<p>Quando invii una candidatura raccogliamo nome, cognome, email, telefono, città, profilo LinkedIn, disponibilità, eventuale presentazione e il CV, oltre a data, indirizzo IP e browser come prova del consenso. I dati sono trasferiti al sistema di selezione del personale aziendale e, dopo il trasferimento, CV e dati non necessari vengono eliminati dal sito. Le candidature non trasferite sono eliminate automaticamente dopo il periodo indicato nelle impostazioni.</p>', 'pm-ats')));
            }
        });
    }

    private static function rows(string $email): array
    {
        global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare('SELECT * FROM ' . PM_ATS_Applications::table() . ' WHERE email = %s', strtolower($email)), ARRAY_A);
    }

    public static function export(string $email, int $page = 1): array
    {
        $data = [];
        $lbl = ['position_title' => __('Posizione', 'pm-ats'), 'first_name' => __('Nome', 'pm-ats'), 'last_name' => __('Cognome', 'pm-ats'),
                'email' => 'Email', 'phone' => __('Telefono', 'pm-ats'), 'city' => __('Città', 'pm-ats'), 'linkedin_url' => 'LinkedIn',
                'availability' => __('Disponibilità', 'pm-ats'), 'salary_expectation' => __('RAL desiderata', 'pm-ats'),
                'cover_letter' => __('Presentazione', 'pm-ats'), 'consent_at' => __('Consenso (UTC)', 'pm-ats'), 'ip' => 'IP',
                'created_at' => __('Inviata (UTC)', 'pm-ats'), 'status' => __('Stato', 'pm-ats')];
        foreach (self::rows($email) as $r) {
            $item = [];
            foreach ($lbl as $k => $l) if ((string)$r[$k] !== '') $item[] = ['name' => $l, 'value' => (string)$r[$k]];
            $data[] = ['group_id' => 'pm-ats', 'group_label' => __('Candidature', 'pm-ats'), 'item_id' => 'pm-ats-' . $r['id'], 'data' => $item];
        }
        return ['data' => $data, 'done' => true];
    }

    public static function erase(string $email, int $page = 1): array
    {
        $n = 0;
        foreach (self::rows($email) as $r) { PM_ATS_Applications::delete((int)$r['id']); $n++; }
        return ['items_removed' => $n > 0, 'items_retained' => false,
                'messages' => $n ? [__('Le candidature già trasferite in PortalManager vanno cancellate anche lì.', 'pm-ats')] : [], 'done' => true];
    }
}
