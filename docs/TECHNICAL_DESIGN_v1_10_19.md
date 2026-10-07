# TECHNICAL DESIGN — v1.10.19 · Struttura della Job Description e layout «Lavora con noi»

## A. Struttura (separata dal layout)
```
PortalManager job_positions ──(WpAtsSync::item: description = '')──► /sync/jobs ──► PM_ATS_Jobs::normalize (description = '')
                                                                                        │
                       PM_ATS_Jobs::STRUCTURE (ordine vincolante) ── sections($job) ── sectionsHtml($job, h, class)
                         │                 │                   │                    │
                    job-single.php   jobs-accordion.php   ?pm_ats_preview     jsonLd() / estratto
PortalManager: WpAtsPreview::STRUCTURE (copia identica) ── anteprima locale
```
`sections()` restituisce `[key, n (1-5), title, parts[field, subtitle, text]]`. `SECTIONS` (campo => titolo) è mantenuta nello stesso ordine per i template del tema 1.0/1.1.

## B. Layout (separato dalla struttura)
| Riferimento (Divi / CF7) | Plugin |
|---|---|
| `et_pb_section_0` (testata) | `.pm-ats-wt-hero` (+ `--pm-ats-wt-hero-img`) |
| `et_pb_section_1` / `et_pb_row_1` | `.pm-ats-wt-body` / `.pm-ats-wt-row` |
| `et_pb_column_1_2` ×2 | `.pm-ats-wt-col-jobs` / `.pm-ats-wt-col-form` (47,25% + 5,5%) |
| `et_pb_text_1 h2` / `et_pb_text_3 h3` / `et_pb_text_4 h3` | `.pm-ats-wt-h2` / `.pm-ats-wt-h3` / `.pm-ats-wt-h3-form` |
| `et_pb_accordion` / `et_pb_toggle` | `.pm-ats-wt-accordion` / `.pm-ats-wt-item` (`.is-open`) |
| `et_pb_toggle_title` / `close-tab` / `et_pb_toggle_content` | `.pm-ats-wt-title > button.pm-ats-wt-toggle` / `.pm-ats-wt-close` / `.pm-ats-wt-content[hidden]` |
| `form-single-column` / `form-row-2col` / `wpcf7-submit` | `.pm-ats-form` / `.pm-ats-grid` (2 colonne, gap 15 px) / `button.pm-ats-btn` |

- Shortcode `[pm_ats_jobs]` con `layout=accordion` (impostazione o attributo `layout="accordion"`, `hero="0|1"`): tutte le posizioni pubblicate (fino a 100) e il modulo `form(0, '', positions)`.
- Il modulo con scelta della posizione invia `job_id` dal campo e il marcatore `_pm_ats_sel`; l'esito torna con `pm_ats_sel=1`, quindi errori, dati inseriti e posizione scelta vengono ripresentati.
- Stili: `pm-ats-wetechs.css` (dipende da `pm-ats.css`) caricato nell'`<head>` delle pagine con lo shortcode e sulla scheda singola quando il layout è attivo.
- Accessibilità: pulsante con `aria-expanded`/`aria-controls`, contenuto `hidden`, focus visibile, `#pm-ats-job-<id>` apre la voce.

## Impostazioni (schema 3)
`color_title`, `color_accent`, `wt_hero`, `wt_hero_title`, `wt_hero_image`, `wt_title` (`{…}` = evidenza), `wt_intro`, `wt_list_title`, `wt_form_title`. Le nuove chiavi vengono aggiunte con i valori predefiniti all'aggiornamento (`PM_ATS_Upgrade::maybe`).
