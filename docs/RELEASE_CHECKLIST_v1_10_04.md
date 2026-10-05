# Release Checklist v1.10.04

| Controllo | Esito |
|---|---|
| VERSION = PM_VERSION = app_version = schema_version = 1.10.04 | OK |
| `php -l` su tutti i PHP modificati | OK |
| Migration RUN1/RUN2 err=0 (Dump 19.80 + DB di collaudo) | OK |
| Nessun `;` nei commenti SQL | OK |
| verify_v1_10_04: 30 OK / 0 KO su entrambi i DB | OK |
| Classi DGB = Relazione IT riga per riga (64.355 moduli, 0 differenze) | OK |
| Partizione ord + fuori + rep = ore con 9 combinazioni di filtri | OK |
| Pagina DGB senza errori PHP (nessun filtro, rep=1, mese), export XLSX dettaglio | OK |
| DgbSync scrive end_at (prova in transazione annullata) | OK |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, CHECKLIST | OK |
| update_manifest.json allineato | OK |
