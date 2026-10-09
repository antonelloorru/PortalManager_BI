# TECHNICAL DESIGN — v1.10.37 (pm-ats 1.3.5)

## Moduli
| File | Modifica |
|---|---|
| `class-pm-ats-settings.php` | chiave `wt_hero_width` (`window` \| `container`, predefinito `window`), whitelist e sanificazione |
| `class-pm-ats-public.php::heroHtml` | classe `pm-ats-wt-hero-full` sulle tre varianti (scale, cover, senza immagine) quando `window` |
| `class-pm-ats-admin.php` | selettore «Larghezza» in Impostazioni › Aspetto › Sezione di testata |
| `assets/pm-ats-wetechs.css` | regole `.pm-ats-wt-hero-full` e `.pm-ats-wt-hero-host` (sotto) |
| `assets/pm-ats.js` | calcolo di larghezza e scostamento (sotto) |

## CSS
- `.pm-ats-wt-hero-full`:
  - `width: var(--pm-ats-hero-w, 100vw)`;
  - `margin-left: var(--pm-ats-hero-ml, calc(50% - 50vw))`;
  - altezza `cover`: `clamp(150px, W·0,328, 560px)`;
  - titolo: `clamp(24px, W·0,052, 60px)`.
- `.pm-ats-wt-hero-host`: `overflow: visible`.

## JavaScript
- `--pm-ats-hero-w` = `document.documentElement.clientWidth`, cioè la finestra senza la barra di scorrimento verticale: nessuno scorrimento orizzontale.
- `--pm-ats-hero-ml` = −scostamento della testata dal bordo sinistro, misurato con margine 0.
- Ricalcolo su `load`, `resize` (con requestAnimationFrame) e `ResizeObserver(body)`.
- Gli antenati con `overflow-x` hidden o clip ricevono `.pm-ats-wt-hero-host`.

## Versioni
`PM_ATS_VERSION` 1.3.5, `PM_ATS_SETTINGS_VERSION` 8 (`PM_ATS_Upgrade::maybe` unisce i valori predefiniti), `PM_ATS_TEMPLATE_VERSION` 1.3.4, asset `@version` 1.3.5 (css wetechs, js).

## Schema ER
Nessuna modifica (PortalManager).
