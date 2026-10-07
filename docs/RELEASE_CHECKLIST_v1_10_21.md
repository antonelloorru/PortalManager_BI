# RELEASE CHECKLIST — v1.10.21

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.21 | ✔ |
| Plugin 1.3.2: Version = PM_ATS_VERSION = Stable tag; schema impostazioni 5; readme e CHANGELOG.md | ✔ |
| migration_v1_10_21.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su PortalManager e plugin | ✔ |
| Tema di prova con comportamento Divi (layout dal meta `_et_pb_page_layout`, widget «Articoli recenti»): scheda posizione e Lavora con noi senza `#sidebar`, classe `et_no_sidebar`, contenuto a 1080 px | ✔ |
| `hide_sidebar` = 0 → barra laterale di nuovo presente (controllo) | ✔ |
| Articoli del sito: barra laterale invariata | ✔ |
| Lavora con noi: elenco e modulo affiancati | ✔ |
| verify_v1_10_21.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetti update_v1.10.21.zip + pm-ats-1.3.2.zip; docs (6); manifest | ✔ |
