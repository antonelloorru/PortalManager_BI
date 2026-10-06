# Release Checklist v1.10.06

| Controllo | Esito |
|---|---|
| VERSION = PM_VERSION = app_version = schema_version = 1.10.06 | OK |
| `php -l` su tutti i PHP nuovi/modificati | OK |
| Migration RUN1/RUN2 err=0 (Dump 19.80 + DB di collaudo), nessun `;` nei commenti SQL | OK |
| verify_v1_10_06: 0 KO su entrambi i DB (import reale dell'export allegato su Dump 19.80) | OK |
| Import XLSX allegato: 3.564 eventi, 762 ticket; reimport XLSX e CSV: 0 nuove righe | OK |
| DB SOC simulato (istanza separata, stesso schema): test, anteprima, salvataggio, sync completa = 0 doppioni, sync incrementale +2 eventi | OK |
| CLI cron_soc_sync: uscita 2 se disattivata, 0 con --force | OK |
| Abbinamenti automatici: 4/4 clienti, 6/8 persone | OK |
| Pagina: 5 schede, filtri, dettaglio ticket, export XLSX, nessun errore PHP | OK |
| Correzione link wp_ats_sync.php | OK |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, CHECKLIST | OK |
| update_manifest.json allineato, ZIP | OK |
