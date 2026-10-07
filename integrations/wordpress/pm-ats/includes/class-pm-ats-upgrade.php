<?php
/**
 * Versioning e manutenzione (v1.1.0).
 *  - maybe(): a ogni caricamento confronta la versione installata (opzione pm_ats_version) con PM_ATS_VERSION ed esegue
 *    le migrazioni idempotenti (schema impostazioni, nuove opzioni), registra lo storico (pm_ats_version_history).
 *  - activate(): al primo avvio imposta la configurazione guidata come «da completare» e reindirizza al wizard.
 *  - onboarding(): stato della configurazione guidata (pending|done, passo corrente, completata il).
 */
defined('ABSPATH') || exit;

final class PM_ATS_Upgrade
{
    public const OPT_VERSION  = 'pm_ats_version';
    public const OPT_HISTORY  = 'pm_ats_version_history';
    public const OPT_SETTINGS = 'pm_ats_settings_version';
    public const OPT_ONBOARD  = 'pm_ats_onboarding';

    public static function activate(): void
    {
        $o = self::onboarding();
        if ($o['status'] !== 'done') {
            update_option(self::OPT_ONBOARD, ['status' => 'pending', 'step' => max(1, (int)$o['step']), 'completed_at' => 0], false);
            set_transient('pm_ats_activation_redirect', 1, 60);
        }
    }

    public static function maybe(): void
    {
        $installed = (string)get_option(self::OPT_VERSION, '');
        if ($installed === PM_ATS_VERSION && get_option(self::OPT_SETTINGS) === PM_ATS_SETTINGS_VERSION) return;

        // installazione precedente alla 1.1.0 già in uso (segreto presente): la configurazione guidata non è obbligatoria
        if ($installed === '' && get_option(PM_ATS_Settings::OPTION) !== false) {
            $installed = '1.0.0';
            if (PM_ATS_Settings::secretSource() !== 'none' && self::onboarding()['status'] !== 'done')
                update_option(self::OPT_ONBOARD, ['status' => 'done', 'step' => 5, 'completed_at' => time(), 'migrated' => 1], false);
        }

        // impostazioni: aggiunge le chiavi nuove con il valore predefinito, conserva quelle esistenti
        $cur = get_option(PM_ATS_Settings::OPTION, []);
        if (is_array($cur)) update_option(PM_ATS_Settings::OPTION, array_merge(PM_ATS_Settings::defaults(), $cur));
        update_option(self::OPT_SETTINGS, PM_ATS_SETTINGS_VERSION);

        if ($installed !== PM_ATS_VERSION) {
            $h = get_option(self::OPT_HISTORY, []);
            $h = is_array($h) ? $h : [];
            $h[] = ['from' => $installed ?: null, 'to' => PM_ATS_VERSION, 'at' => time()];
            update_option(self::OPT_HISTORY, array_slice($h, -20), false);
            update_option(self::OPT_VERSION, PM_ATS_VERSION);
            if (class_exists('PM_ATS_Log')) PM_ATS_Log::add('upgrade', 200, ($installed ?: 'nuova installazione') . ' → ' . PM_ATS_VERSION);
        }
    }

    /** @return array{status:string,step:int,completed_at:int} */
    public static function onboarding(): array
    {
        $o = get_option(self::OPT_ONBOARD, []);
        $o = is_array($o) ? $o : [];
        return ['status' => (string)($o['status'] ?? 'pending'), 'step' => (int)($o['step'] ?? 1), 'completed_at' => (int)($o['completed_at'] ?? 0)] + $o;
    }

    public static function setStep(int $step, bool $done = false): void
    {
        $o = self::onboarding();
        $o['step'] = max((int)$o['step'], $step);
        if ($done) { $o['status'] = 'done'; $o['completed_at'] = time(); }
        update_option(self::OPT_ONBOARD, $o, false);
    }

    public static function restart(): void
    {
        update_option(self::OPT_ONBOARD, ['status' => 'pending', 'step' => 1, 'completed_at' => 0], false);
    }

    /**
     * Template sovrascritti dal tema con versione più vecchia di quella del plugin.
     * @return array<int,array{file:string,theme_version:string,plugin_version:string,outdated:bool}>
     */
    public static function templateOverrides(): array
    {
        $out = [];
        foreach ((array)glob(PM_ATS_DIR . 'templates/*.php') as $tpl) {
            $f = basename((string)$tpl);
            $theme = locate_template(['pm-ats/' . $f]);
            if (!$theme) continue;
            $pv = self::fileVersion((string)$tpl); $tv = self::fileVersion($theme);
            $out[] = ['file' => $f, 'path' => $theme, 'theme_version' => $tv ?: '—', 'plugin_version' => $pv, 'outdated' => $tv === '' || version_compare($tv, $pv, '<')];
        }
        return $out;
    }

    public static function fileVersion(string $path): string
    {
        $head = (string)@file_get_contents($path, false, null, 0, 2048);
        return preg_match('/@version\s+(\d+\.\d+\.\d+)/', $head, $m) ? $m[1] : '';
    }
}
