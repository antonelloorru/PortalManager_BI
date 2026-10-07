# RELEASE CHECKLIST — v1.10.16

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.16 | ✔ |
| Plugin: header Version = PM_ATS_VERSION = Stable tag = 1.1.1; readme Upgrade Notice/Changelog; CHANGELOG.md; asset/template invariati (@version 1.1.0) | ✔ |
| migration_v1_10_16.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980; nessun `;` nei commenti | ✔ |
| `php -l` su PortalManager e sui file del plugin | ✔ |
| Causa riprodotta con plugin 1.1.0 (20 OK, poi 429); corretta con 1.1.1 (25/25 test, 12/12 sincronizzazioni, 0 «replay») | ✔ |
| Scenari con codice effettivo: OK, bad_signature (rotta), unknown_client, ip_not_allowed (IP visto), 429, rest_no_route (plugin disattivo), 403 Cloudflare HTML, 503 manutenzione, 301 (URL suggerito), cURL 60 (certificato autofirmato), DNS | ✔ |
| Browser: Impostazioni (Diagnostica, impronta), test KO → diagnostica automatica, wizard passo 3, link da Sincronizzazione, test OK | ✔ |
| Impronta del segreto identica fra PortalManager e plugin; nessun segreto in DB, log o risposte | ✔ |
| verify_v1_10_16.php 0 KO su pmrepo (--online=25) e pm1980 | ✔ |
| Pacchetti update_v1.10.16.zip + pm-ats-1.1.1.zip; docs (6) | ✔ |
