# RELEASE CHECKLIST — v1.10.20

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.20 | ✔ |
| Plugin 1.3.1: Version = PM_ATS_VERSION = Stable tag; schema impostazioni 4; template jobs-accordion 1.3.1; readme e CHANGELOG.md | ✔ |
| migration_v1_10_20.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su PortalManager e plugin | ✔ |
| Aggiornamento da 1.3.0 con layout «grid» → «accordion» + elenco «link» (schema 4) | ✔ |
| Pagina a 1400 px: elenco a sinistra (4 titoli cliccabili verso le schede) e modulo a destra sulla stessa riga | ✔ |
| Clic sulla casella → scheda della posizione | ✔ |
| Contenitore stretto 520 px → modulo sotto l'elenco, senza uscire dal contenitore | ✔ |
| verify_v1_10_20.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetti update_v1.10.20.zip + pm-ats-1.3.1.zip; docs (6); manifest | ✔ |
