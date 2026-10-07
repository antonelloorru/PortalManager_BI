# CHANGELOG — v1.10.22 (2026-10-07)

Software 1.10.22 · Schema 1.10.22 · Upgrade `sql/migration_v1_10_22.sql` (cumulativo da 1.10.06) · Plugin pm-ats **1.3.3**

## Titolo della pagina «Lavora con noi» opzionale e personalizzabile
Il testo «Lavora con noi» sopra il contenuto (`<h1 class="entry-title main_title">` in Divi) è il titolo della pagina WordPress stampato dal tema. Il plugin 1.3.3 aggiunge *Lavora con noi › Impostazioni › Aspetto › Titolo della pagina*:
- **Mostra il titolo della pagina (tema)** — predefinito, comportamento invariato;
- **Nascondi il titolo** — l'h1 non viene stampato (o resta vuoto e nascosto da CSS, come in Divi);
- **Mostra un testo personalizzato** — sostituisce il testo dell'h1 (max 150 caratteri; vuoto = titolo della pagina).

Ambito: pagina elenco impostata e pagine con `[pm_ats_jobs]`, `[pm_ats_apply]` o `[pm_ats_lavora_con_noi]`. Invariati: titolo della scheda della posizione, `<title>` del browser, voci di menu, SEO.

PortalManager: `WpAtsConfig::PLUGIN_RECOMMENDED` = 1.3.3.
