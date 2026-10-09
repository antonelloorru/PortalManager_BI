# TECHNICAL DESIGN — v1.10.38 (pm-ats 1.3.6)

## Impostazioni (schema 9)
| Chiave | Valori | Predefinito |
|---|---|---|
| `wt_hero_fit` | `band` \| `scale` \| `cover` | `band` |
| `wt_hero_height` | intero, 150–900 (limitato lato server) | 400 |
| `wt_hero_pos` | `top` \| `center` \| `bottom` | `center` |

`PM_ATS_Upgrade::maybe`: se lo schema precedente è minore di 9 e `wt_hero_fit` vale `scale`, lo imposta a `band` e annota l'operazione nel registro.

## Rendering (`PM_ATS_Public::heroHtml`, modalità `band`)
`<section class="pm-ats-wt-hero pm-ats-wt-hero-band pm-ats-wt-hero-full" style="--pm-ats-hero-h:400px;--pm-ats-hero-pos:center center">`, contenente:
- `<img class="pm-ats-wt-hero-img">`, generata da `wp_get_attachment_image` con srcset e `sizes="100vw"`;
- `.pm-ats-wt-hero-over`, con gradiente e titolo.

## CSS (`pm-ats-wetechs.css` 1.3.6)
- `.pm-ats-wt-hero-band`: `height: var(--pm-ats-hero-h)`, `overflow: hidden`.
- Immagine: `position: absolute`, 100% × 100%, `object-fit: cover`, `object-position: var(--pm-ats-hero-pos)`.
- Effetto: larghezza della finestra (1.3.5: `.pm-ats-wt-hero-full` con `pm-ats.js`), altezza costante; in larghezza l'immagine scala quando la finestra è più larga di altezza × rapporto dell'immagine, altrimenti è ritagliata ai lati.

## Versioni
`PM_ATS_VERSION` 1.3.6 · `PM_ATS_SETTINGS_VERSION` 9 · `PM_ATS_TEMPLATE_VERSION` 1.3.4 (template invariati) · asset `@version` 1.3.6.

## Schema ER
Nessuna modifica (PortalManager).
