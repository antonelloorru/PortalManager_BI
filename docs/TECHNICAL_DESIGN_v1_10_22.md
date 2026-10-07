# TECHNICAL DESIGN — v1.10.22 · Titolo della pagina opzionale (pm-ats 1.3.3)

| Impostazione | Valori | Predefinito |
|---|---|---|
| `page_title_mode` | `show` · `hide` · `custom` | `show` |
| `page_title_text` | testo (sanitize_text_field, max 150) | vuoto |

Schema impostazioni 6 (`PM_ATS_Upgrade::maybe` integra le nuove chiavi con i valori predefiniti). Tab Aspetto (`TABS['aspetto']`), salvataggio parziale per tab invariato.

| Hook | Effetto |
|---|---|
| `the_title` (20) → `PM_ATS_Public::pageTitle` | Solo frontend, non feed/AJAX/REST; solo se `$id` = oggetto richiesto, `is_singular()`, `in_the_loop()`, `is_main_query()` e `isListPage($id)`. `hide` → stringa vuota; `custom` → `esc_html(page_title_text)`. |
| `body_class` (99) | `pm-ats-hide-title` / `pm-ats-custom-title` sulle pagine elenco. |
| `pm-ats.css` | `body.pm-ats-hide-title .entry-title.main_title`, `article.page>.entry-header>.entry-title`, `h1.wp-block-post-title`, `h1.entry-title:empty` → `display:none`; `.entry-header` rimasta vuota nascosta. |

Riconoscimento pagine (refactoring di `isPluginPage`):
- `matchPage($postId, $withJobs)` è la logica comune;
- `isPluginPage()` = `hide_sidebar` attivo + `matchPage(…, true)`, include le schede `pm_job`;
- `isListPage()` = `matchPage(…, false)`: pagina `list_page_id` o contenuto con shortcode pm-ats, escluse schede e archivio `pm_job`.

Effetti per tema:
- **Divi** (`<h1 class="entry-title main_title"><?php the_title(); ?></h1>`): `hide` lascia l'h1 vuoto, nascosto via CSS.
- **Temi classici e a blocchi** che usano `the_title($before, $after)` o `core/post-title`: l'h1 non viene stampato.
- `<title>` (`single_post_title`/`document_title_parts`) e menu (ID della voce di menu ≠ oggetto richiesto) non sono toccati.
