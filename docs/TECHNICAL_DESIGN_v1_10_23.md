# TECHNICAL DESIGN — v1.10.23 · Testata adattiva e Libreria media (pm-ats 1.3.4)

| Impostazione | Valori | Predefinito |
|---|---|---|
| `wt_hero_image` | URL (esc_url_raw) | vuoto |
| `wt_hero_image_id` | ID allegato; 0 se non immagine, non leggibile dall'utente o URL vuoto | 0 |
| `wt_hero_fit` | `scale` · `cover` | `scale` |

Schema impostazioni 7; template `jobs-accordion.php` @version 1.3.4 (`PM_ATS_TEMPLATE_VERSION` 1.3.4).

## `PM_ATS_Public::heroHtml(array $s)`
Composta in `scJobs` e passata al template come `$hero_html`. I template sovrascritti senza la variabile chiamano `heroHtml()` direttamente.

| Caso | Markup | CSS |
|---|---|---|
| nessuna immagine | `section.pm-ats-wt-hero-noimg` | padding `clamp(20px,4.4cqi,56px)` |
| `scale` | `section.pm-ats-wt-hero-scale > img.pm-ats-wt-hero-img + .pm-ats-wt-hero-over` | img `width:100%; height:auto !important`, overlay assoluto con gradiente, titolo in basso |
| `cover` | `section.pm-ats-wt-hero-cover` con `--pm-ats-wt-hero-img` (URL `full` dell'allegato se c'è un ID) | `height:clamp(150px,32.8cqi,560px)`, `background-size:cover` |

Titolo `.pm-ats-wt-h1`: `clamp(24px,5.2cqi,60px)`; le dimensioni fisse 56/40 px nelle media query sono state rimosse. Il contenitore è `.pm-ats-wt` (`container-type:inline-size`).

Scelta dell'allegato (`scale`):
1. `wt_hero_image_id`, valido se `wp_attachment_is_image` e se `sameMedia(url, wp_get_attachment_url(id))` (confronto senza schema e senza suffissi `-WxH`/`-scaled`);
2. altrimenti `attachment_url_to_postid(url)`;
3. con un allegato: `wp_get_attachment_image(id,'full',…,sizes='100vw', loading=eager, fetchpriority=high)` → `srcset`;
4. senza allegato: `<img>` semplice.

## Admin
- `assets()` (pagine impostazioni/configurazione guidata) chiama `wp_enqueue_media()` e carica `assets/pm-ats-admin.js`.
- `pm-ats-admin.js`:
  - `wp.media` con libreria limitata alle immagini e selezione singola;
  - alla scelta imposta URL e ID e mostra l'anteprima `medium`;
  - «Rimuovi» azzera URL e ID;
  - la modifica manuale dell'URL azzera l'ID.
- Sanitizzazione dell'ID: `wp_attachment_is_image` e `current_user_can('read_post', id)`.

## Riferimenti di creazione
- Testo in `PM_ATS_Admin::CREDITS`.
- `credits()` lo stampa in fondo a `pageSettings()` per tutti i tab, con `PM_ATS_VERSION`.
- Il filtro `admin_footer_text` lo usa sulle schermate il cui `screen->id` contiene `pm-ats`.
