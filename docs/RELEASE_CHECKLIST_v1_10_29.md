# RELEASE CHECKLIST — v1.10.29

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.29 | ✔ |
| migration_v1_10_29.sql: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su tutti i file modificati | ✔ |
| Relazione Tecnici: colonna «Descrizione tariffa» al posto di «Fascia di costo», 18 valori nel filtro, filtro applicato a tabelle ed export (pmrepo con copia, pm1980 con vista; collazioni allineate) | ✔ |
| Commesse / Progetti: «Tipo» multiplo (sl[]), elenco ed export filtrati sui tipi scelti, compatibilità `sl=<valore>` | ✔ |
| Scheda Progetto › Consuntivo: colonna «Descrizione tariffa», fascia di costo nel dettaglio | ✔ |
| Playwright sulle tre pagine, nessun errore PHP | ✔ |
| verify_v1_10_29.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto, docs (6), manifest | ✔ |
