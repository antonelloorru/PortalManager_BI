# RELEASE CHECKLIST — v1.10.26

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.26 | ✔ |
| migration_v1_10_26.sql: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` sulle pagine; sintassi JS di pm-multiselect | ✔ |
| Playwright: tendina «Incaricato» (150 voci) in `body`, `position:fixed`, intera nella finestra; ultima voce visibile dopo lo scorrimento della lista; riposizionata allo scorrimento della pagina; apertura verso l'alto vicino al fondo | ✔ |
| Tastiera (ricerca, frecce, Invio), clic su voce (resta aperta), clic esterno (chiude e torna nel campo) | ✔ |
| Sei pagine con filtro contratto attivo: nessun riquadro esterno, pannello aperto con contatore, un solo form GET, nessun errore PHP | ✔ |
| DGB vista giornaliera senza selettore del mese a sé stante | ✔ |
| verify_v1_10_26.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto, docs (6), manifest | ✔ |
