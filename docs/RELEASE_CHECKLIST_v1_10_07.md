# Release Checklist v1.10.07

| Controllo | Esito |
|---|---|
| VERSION = PM_VERSION = app_version = schema_version = 1.10.07 | OK |
| `php -l` su tutti i PHP nuovi/modificati | OK |
| Migration RUN1/RUN2 err=0 (Dump 19.80 + DB di collaudo), nessun `;` nei commenti SQL | OK |
| verify_v1_10_07: 0 KO su entrambi i DB | OK |
| Pipeline da riga di comando: file in cartella di arrivo + DB SOC in un'esecuzione, file archiviato, 2ª esecuzione «non dovuta» | OK |
| Scheduler del portale: tick → worker task «soc» → esecuzione «pianificata» registrata | OK |
| Sincronizzazione gestionale › SOC: caricamento, Esegui ora, impostazioni, nessun errore PHP | OK |
| Service SOC: 4 schede senza Ingestion, link alla sincronizzazione, colonna Unità nel Team | OK |
| Unità SOC: senza unità → assegnato; altra unità → segnalato; forzatura → spostato (transazione annullata) | OK |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, CHECKLIST | OK |
| update_manifest.json allineato, ZIP | OK |
