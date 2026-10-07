# RELEASE CHECKLIST — v1.10.17

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.17 | ✔ |
| migration_v1_10_17.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980; nessun `;` nei commenti | ✔ |
| `php -l` su tutti i PHP modificati | ✔ |
| cURL 28 in connessione: messaggio «connessione TCP non stabilita» + passi 3b/3c/3d (IP non instradabile, 15 s) | ✔ |
| IP forzato: URL `http://localhost:8098` con IP 127.0.0.1 → handshake riuscito; IP non valido rifiutato | ✔ |
| Handshake normale invariato (OK) | ✔ |
| verify_v1_10_17.php 0 KO su pmrepo (--online=25) e pm1980 | ✔ |
| Pacchetto cumulativo, docs (6), manifest | ✔ |
