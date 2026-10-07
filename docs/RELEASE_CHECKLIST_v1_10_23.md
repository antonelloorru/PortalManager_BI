# RELEASE CHECKLIST — v1.10.23

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.23 | ✔ |
| Plugin 1.3.4: Version = PM_ATS_VERSION = Stable tag; schema impostazioni 7; template 1.3.4; readme e CHANGELOG.md; PLUGIN_RECOMMENDED = 1.3.4 | ✔ |
| migration_v1_10_23.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su PortalManager e plugin | ✔ |
| Playwright 1440/1024/600/375 px, Twenty Twenty-One e tema di prova Divi, `scale` e `cover`: altezza proporzionale, titolo fluido 60→24 px, nessuno scorrimento orizzontale | ✔ |
| srcset: 1536 px su desktop, 1024 px su tablet, 768 px su telefono | ✔ |
| Admin: «Scegli dalla Libreria media» apre wp.media, la selezione imposta URL+ID e anteprima; «Rimuovi» azzera | ✔ |
| ID non coerente con l'URL ignorato; ID inesistente → 0; `wt_hero_fit` non ammesso → `scale` | ✔ |
| Riferimenti di creazione in fondo alle impostazioni e nel piè di pagina; nessun warning PHP | ✔ |
| verify_v1_10_23.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetti update_v1.10.23.zip + pm-ats-1.3.4.zip; docs (6); manifest | ✔ |
