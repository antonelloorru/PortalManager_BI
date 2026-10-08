# RELEASE CHECKLIST — v1.10.27

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.27 | ✔ |
| migration_v1_10_27.sql: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l file_manager.php` | ✔ |
| Playwright: menu → File manager, apertura cartella, breadcrumb e home, download (contenuto integro), upload, nuova cartella, modifica e salvataggio (ritorno alla cartella), rinomina (anche con apice), elimina, ZIP (PK), ricarica senza reinvio | ✔ |
| Cartella di sistema non eliminabile; POST senza token CSRF → 403; SVG visualizzato come testo con CSP sandbox | ✔ |
| verify_v1_10_27.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto, docs (6), manifest | ✔ |
