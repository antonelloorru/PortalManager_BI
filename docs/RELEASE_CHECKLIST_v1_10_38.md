# RELEASE CHECKLIST — v1.10.38

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.38 | ✔ |
| migration_v1_10_38.sql: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| Plugin 1.3.6: header, PM_ATS_VERSION, Stable tag, @version asset, CHANGELOG, readme; schema impostazioni 9 | ✔ |
| `php -l` su tutti i file del plugin e su WpAtsConfig; sintassi di pm-ats.js | ✔ |
| Migrazione scale → band applicata (WordPress di prova: 1.3.5 → 1.3.6) | ✔ |
| Divi: testata 400 px di altezza a 1920/1366/1000/600/390 px, larghezza = finestra, nessuno scorrimento orizzontale | ✔ |
| Immagine di prova a griglia: a 1920 px lati interi e ritaglio verticale; sotto i 1200 px ritaglio laterale centrato | ✔ |
| Impostazioni › Aspetto: altezza (limite 900 lato server), parte visibile, salvataggio e ripristino | ✔ |
| PLUGIN_RECOMMENDED = 1.3.6 | ✔ |
| verify_v1_10_38.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto, pm-ats-1.3.6.zip, docs (6), manifest | ✔ |
