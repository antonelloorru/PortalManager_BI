# RELEASE CHECKLIST — v1.10.19

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.19 | ✔ |
| Plugin 1.3.0: Version = PM_ATS_VERSION = Stable tag; template 1.3.0; impostazioni schema 3; MIN_PM 1.10.19; readme e CHANGELOG.md | ✔ |
| migration_v1_10_19.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su PortalManager e plugin; sintassi JS | ✔ |
| Struttura: POS-3 nell'ordine 1-5 con sottotitoli di Competenze e nota di chiusura; anteprima dal sito 1-5; anteprima locale 1-5 | ✔ |
| Nota interna: 0 occorrenze di «Per Ral…» nei dati del sito dopo l'invio, nella pagina e nell'anteprima | ✔ |
| Layout: confronto visivo con il riferimento (testata, titolo con evidenza, fisarmonica, riquadro modulo); stili nell'head (nessun cambio di stile al caricamento) | ✔ |
| Fisarmonica: apertura/chiusura, ×, una voce aperta, preselezione della posizione | ✔ |
| Modulo con scelta: candidatura inviata (esito e riferimento); errore email con posizione e dati mantenuti | ✔ |
| Mobile 390 px senza scorrimento orizzontale; tema a contenuto stretto → a tutta larghezza | ✔ |
| verify_v1_10_19.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetti update_v1.10.19.zip + pm-ats-1.3.0.zip; docs (6); manifest | ✔ |
