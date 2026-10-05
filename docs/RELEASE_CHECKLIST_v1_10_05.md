# Release Checklist v1.10.05

| Controllo | Esito |
|---|---|
| VERSION = PM_VERSION = app_version = schema_version = 1.10.05 | OK |
| Plugin pm-ats: versione 1.0.0 coerente (intestazione, costante, readme) | OK |
| `php -l` su tutti i PHP modificati/nuovi (PortalManager + plugin) | OK |
| Migration RUN1/RUN2 err=0 (Dump 19.80 + DB di collaudo), nessun `;` nei commenti SQL | OK |
| verify_v1_10_05: nessun KO su entrambi i DB (`--live` OK sul sito di collaudo) | OK |
| Firma HMAC: assenza firma, firma errata, replay, timestamp scaduto, client errato, corpo alterato → 401 | OK |
| Push: 4 nuove → re-push 4 invariate → pausa = ritirata (302 all'elenco) → riapertura = ripubblicata | OK |
| Invio immediato da Posizioni aperte (pausa) registrato con origine «modifica» | OK |
| Candidature: per posizione (PDF + presentazione), spontanea (DOCX, mobile), duplicato rifiutato con campi conservati, file falso rifiutato | OK |
| Pull: candidato + CV + lettera + candidatura cv_received; spontanea senza candidatura; secondo pull = 0; ack → CV eliminato dal sito | OK |
| Pubblicazioni canale wordpress con URL scheda; `removed` alla ritirata | OK |
| Pagina Sito web (WordPress): salvataggio config (segreto in .env.php), test, sincronizza tutto, nessun errore PHP | OK |
| WordPress: tema a blocchi (Twenty Twenty-Five) e classico (Twenty Twenty-One), pagine admin senza errori, nessun errore JS | OK |
| Disinstallazione con pulizia: tabelle, posizioni, opzioni e cartella CV rimosse; riattivazione OK | OK |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, CHECKLIST + readme.txt plugin | OK |
| update_manifest.json allineato; ZIP aggiornamento + ZIP plugin installabile | OK |
