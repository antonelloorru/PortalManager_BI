# RELEASE CHECKLIST — v1.10.22

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.22 | ✔ |
| Plugin 1.3.3: Version = PM_ATS_VERSION = Stable tag; schema impostazioni 6; readme e CHANGELOG.md; PLUGIN_RECOMMENDED = 1.3.3 | ✔ |
| migration_v1_10_22.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su PortalManager e plugin | ✔ |
| Tema di prova Divi (`h1.entry-title.main_title` con `the_title()`): show = «Lavora con noi», hide = h1 vuoto `display:none` (Playwright), custom = testo impostato e codificato | ✔ |
| Twenty Twenty-One: hide = h1 non stampato e intestazione vuota nascosta; custom = testo; show = invariato | ✔ |
| Scheda posizione: titolo della posizione invariato; `<title>` della pagina invariato | ✔ |
| Sanitizzazione: valore non ammesso → `show`; HTML rimosso dal testo; salvataggio parziale del tab Aspetto | ✔ |
| Schermata Impostazioni › Aspetto con «Titolo della pagina»; nessun warning PHP | ✔ |
| verify_v1_10_22.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetti update_v1.10.22.zip + pm-ats-1.3.3.zip; docs (6); manifest | ✔ |
