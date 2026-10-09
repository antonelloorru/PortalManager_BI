# CHANGELOG — v1.10.38 (2026-10-09)

Software 1.10.38 · Schema 1.10.38 · Upgrade `sql/migration_v1_10_38.sql` (cumulativo da 1.10.06) · **Plugin pm-ats 1.3.6** (`integrations/wordpress/pm-ats-1.3.6.zip`)

## pm-ats 1.3.6 — testata «Lavora con noi» a fascia ad altezza fissa
- **Problema** (1.3.5): la testata usava l'immagine intera proporzionale. Allargando o stringendo la finestra cambiava l'**altezza** (adattamento verticale) invece di adattarsi in **larghezza**.
- **Correzione**: nuova modalità predefinita **«Fascia ad altezza fissa»** (`wt_hero_fit = band`):
  - la testata è larga quanto la finestra e alta **Altezza della fascia** px (predefinito 400, da 150 a 900) a qualsiasi larghezza;
  - l'immagine riempie la fascia e si adatta in larghezza; l'eccedenza verticale è ritagliata attorno a **Parte visibile dell'immagine** (alto / centro / basso);
  - titolo `clamp(28px, 4vw, 60px)` e gradiente del riferimento.
- Migrazione una tantum (schema impostazioni 9): chi aveva «immagine intera proporzionale» passa alla fascia ad altezza fissa. Le modalità precedenti restano selezionabili.
- Su schermi più stretti del rapporto dell'immagine all'altezza scelta (es. immagine 3:1 con fascia di 400 px, sotto i 1200 px) l'altezza resta fissa e l'immagine viene ritagliata ai lati, centrata.

## PortalManager
- `WpAtsConfig::PLUGIN_RECOMMENDED = 1.3.6`.
