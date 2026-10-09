# RELEASE CHECKLIST — v1.10.37

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.37 | ✔ |
| migration_v1_10_37.sql: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| Plugin 1.3.5: header, PM_ATS_VERSION, Stable tag, @version asset, CHANGELOG, readme; schema impostazioni 8 | ✔ |
| `php -l` su tutti i file del plugin e su WpAtsConfig; sintassi di pm-ats.js | ✔ |
| Divi (contenitore 1080 px): testata = larghezza finestra a 1920/1366/800/390 px, nessuno scorrimento orizzontale | ✔ |
| Ridimensionamento dinamico 1600→1100→2200 px, senza JavaScript, modalità cover, modalità «contenitore» | ✔ |
| Tema predefinito (twentytwentyone): nessuna regressione | ✔ |
| Impostazioni › Aspetto: selettore Larghezza, salvataggio e ripristino | ✔ |
| PLUGIN_RECOMMENDED = 1.3.5 | ✔ |
| verify_v1_10_37.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto, pm-ats-1.3.5.zip, docs (6), manifest | ✔ |
