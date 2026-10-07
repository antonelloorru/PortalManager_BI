<?php
/**
 * app/WpAtsPreview.php — v1.10.18
 * Anteprima LOCALE della scheda annuncio, usata quando il sito non è raggiungibile o il plugin è < 1.2.0.
 * Riproduce il template job-single del plugin pm-ats (stesse sezioni, stesso ordine, stessa formattazione del testo,
 * foglio di stile del plugin incluso dal pacchetto integrations/wordpress/pm-ats). Non riproduce il tema del sito:
 * l'anteprima fedele è quella generata dal plugin (WpAtsSync::preview).
 */
declare(strict_types=1);

final class WpAtsPreview
{
    /** Stesse sezioni di PM_ATS_Jobs::SECTIONS. */
    public const SECTIONS = [
        'presentation_text' => 'Chi siamo', 'description' => 'La posizione', 'required_skills' => 'Requisiti',
        'hard_skills' => 'Competenze tecniche', 'soft_skills' => 'Competenze trasversali', 'nice_to_have' => 'Costituisce titolo preferenziale',
        'we_offer' => 'Cosa offriamo', 'benefits' => 'Benefit', 'offer_info' => 'Informazioni sull\'offerta', 'gender_disclaimer' => '',
    ];

    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

    /** Come PM_ATS_Jobs::format(): paragrafi ed elenchi da testo semplice. */
    public static function format(string $t): string
    {
        $t = trim(str_replace("\r\n", "\n", strip_tags($t)));
        if ($t === '') return '';
        $html = ''; $list = []; $para = [];
        $flushL = function () use (&$list, &$html) { if ($list) { $html .= '<ul>' . implode('', array_map(fn($x) => '<li>' . self::e($x) . '</li>', $list)) . '</ul>'; $list = []; } };
        $flushP = function () use (&$para, &$html) { if ($para) { $html .= '<p>' . implode('<br>', array_map([self::class, 'e'], $para)) . '</p>'; $para = []; } };
        foreach (explode("\n", $t) as $line) {
            $l = trim($line);
            if (preg_match('/^(?:[-*•·▪◦]|\d+[.)])\s+(.+)$/u', $l, $m)) { $flushP(); $list[] = $m[1]; continue; }
            $flushL();
            if ($l === '') { $flushP(); continue; }
            $para[] = $l;
        }
        $flushL(); $flushP();
        return $html;
    }

    public static function html(array $job, string $state, string $reason = ''): string
    {
        $css = (string)@file_get_contents(dirname(__DIR__) . '/integrations/wordpress/pm-ats/assets/pm-ats.css');
        $chips = array_filter([$job['location'] ?? '', $job['contract_type'] ?? '', $job['remote_policy'] ?? '', $job['department'] ?? '']);
        $o = '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
           . '<title>Anteprima — ' . self::e((string)$job['title']) . '</title><style>' . $css
           . 'body{margin:0;font-family:system-ui,Segoe UI,Arial,sans-serif;color:#1e293b;background:#f8fafc}.bar{position:sticky;top:0;background:#1e293b;color:#fff;font-size:13px;padding:8px 16px;display:flex;gap:12px;flex-wrap:wrap}'
           . '.bar b{background:#f59e0b;color:#1e293b;border-radius:4px;padding:1px 8px}.wrap{max-width:960px;margin:0 auto;padding:28px 20px;background:#fff;min-height:100vh}.note{font-size:12px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:8px 10px;border-radius:6px;margin-bottom:16px}'
           . '</style></head><body><div class="bar"><b>ANTEPRIMA LOCALE</b><span>Stato sul sito: ' . self::e($state) . '</span><span>' . self::e((string)($job['code'] ?? '')) . '</span></div><div class="wrap">'
           . '<div class="note">Resa del template del plugin senza il tema del sito' . ($reason !== '' ? ' — anteprima del sito non disponibile: ' . self::e($reason) : '') . '.</div>'
           . '<h1>' . self::e((string)$job['title']) . '</h1><div class="pm-ats pm-ats-single"><ul class="pm-ats-chips">';
        foreach ($chips as $c) $o .= '<li class="pm-ats-chip">' . self::e((string)$c) . '</li>';
        if ((int)($job['positions_expected'] ?? 1) > 1) $o .= '<li class="pm-ats-chip">' . (int)$job['positions_expected'] . ' posizioni</li>';
        $o .= '</ul><p><a class="pm-ats-btn" href="#pm-ats-form">Candidati ora</a></p>';
        foreach (self::SECTIONS as $k => $h) {
            if (trim((string)($job[$k] ?? '')) === '') continue;
            $o .= '<section class="pm-ats-section pm-ats-section-' . $k . '">' . ($h !== '' ? '<h2>' . self::e($h) . '</h2>' : '') . self::format((string)$job[$k]) . '</section>';
        }
        return $o . '<div class="pm-ats-form" id="pm-ats-form"><p><strong>Modulo di candidatura</strong> — in anteprima l\'invio è disattivato.</p></div></div></div></body></html>';
    }
}
