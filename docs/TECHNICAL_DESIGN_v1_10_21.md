# TECHNICAL DESIGN — v1.10.21 · Pagine del plugin senza barra laterale (pm-ats 1.3.2)

| Hook | Effetto |
|---|---|
| `get_post_metadata` (`_et_pb_page_layout`) | Per le pagine del plugin restituisce `et_no_sidebar`. Divi (`et_divi_sidebar_class`, `single.php`/`page.php`) non stampa `#sidebar`. Valore calcolato al volo: nessuna scrittura nel database. |
| `is_active_sidebar` | `false` nelle pagine del plugin: i temi che stampano la barra solo se attiva la omettono. |
| `body_class` (99) | Rimuove `et_right_sidebar` / `et_left_sidebar` / `has-sidebar` …; aggiunge `pm-ats-no-sidebar` (+ `et_no_sidebar` su Divi). |
| `pm-ats.css` | `body.pm-ats-no-sidebar #sidebar, .widget-area, #secondary {display:none}`; `#left-area/#primary` 100%; `.single-pm_job .post-meta` nascosta. |

Pagina del plugin (`PM_ATS_Public::isPluginPage`):
- singolare `pm_job` o archivio `pm_job`;
- `list_page_id`;
- pagina il cui contenuto contiene `[pm_ats_jobs`, `[pm_ats_apply` o `[pm_ats_lavora_con_noi`. Il risultato viene memorizzato per richiesta.

Disattivabile con `hide_sidebar` = 0. Impostazioni schema 5.
