# Deployment — v1.9.71
1. Estrarre lo ZIP nella root del portale (struttura: file .php in root, `app/`, `assets/`).
   Il pacchetto include versioni aggiornate di file toccati da release precedenti
   (login, report_certificazioni, manage_employees, employee_profile, auth_microsoft,
   MenuManager, Router, manage_permissions): conservano tutte le modifiche fino alla v1.9.70.
2. Eseguire `sql/migration_v1_9_71.sql`.
3. Ctrl+F5 (nuovi CSS/JS).

## Verifica
| Passo | Esito atteso |
|---|---|
| Menu | presenti "Import Pratix" e "Import certificazioni Omnissa" |
| Una select multipla (es. Relazione Servizio IT) | ricerca, spunte, Seleziona visibili/Nessuno |
| Service Desk → filtro tecnico | ricerca, selezione singola |
| Pagina con elenco (es. Timesheet) | barra Cerca/Filtri/Viste/Esporta sopra la tabella |
| Filtri → colonna a elenco / importo / data | multi-selezione / intervallo / intervallo date |
| Esporta → OpenDocument (.odt) | file apribile in LibreOffice/Word |
| Anagrafiche, liste, stampe | nominativi "Cognome Nome" |
