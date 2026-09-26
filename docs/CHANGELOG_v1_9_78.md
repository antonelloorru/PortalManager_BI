# PortalManager v1.9.78 — Filtro globale Codice Contratto / PM Project su Service Desk, Report direzionale, DGB

## Componente condiviso — `app/PmContractFilter.php` (nuovo)
- Valori: `project_code` oppure `dgb:<id_contract>`; validazione in `norm()`.
- Risoluzione **una volta per richiesta** in quattro liste chiuse, via `cm_projects.dgb_contract_id`
  (bidirezionale): codici commessa, id `cm_projects`, id contratto DGB, ticket SD collegati.
- `sql($kind, $col, &$args)`: `IN (...)` con parametri preparati; filtro attivo senza corrispondenze → `0=1`.
  Nessuna sottoquery sulle viste annidate (v1.8.88: su `v_cm_sd_ticket` IN/EXISTS erano inaffidabili).
- **Globale fra le pagine**: selezione in sessione (`pm_contratti`). `contratti` / `contratti_set` nella
  richiesta la sostituiscono (vuota = rimozione); link storici `contract=<id>` accettati.
- UI comune: `field()` (select multipla con ricerca `pm-ms`), `banner()` con «Rimuovi filtro contratto»,
  `describe()` per stampa/export.
- La Relazione di Servizio IT (v1.9.77) usa ora lo stesso componente: risultati invariati.

## Service Desk (`service_desk.php`, `app/SdModel.php`)
I ticket non portano la commessa: legame **ticket attività DGB** (`dgb_forms_activity.ticket`,
contratto) **∪ ticket rapportino** (`cm_intervention_reports.ticket`, commessa).
| Componente | Chiave |
|---|---|
| KPI, ripartizione, da presidiare, code, andamento (mensile/giornaliero), operatori | ticket |
| Scheda componente: periodo, esito, mesi, code, ticket, quota per coda | ticket |
| Operatività componenti (prese in carico, messaggi, moduli) | ticket / codice commessa |
| Squadra: quadro, dettaglio, fasce, contratti | codice commessa (moduli) |
| Moduli per contratto/codice linea/azienda, riepilogo moduli | codice commessa |
| OBJ_2.1/2.2/2.3 (attività, fatturabili, interne, tecnici), costi | codice commessa |
| OBJ_2 quadro, linee, addetti, commesse; OBJ_2.3 ripartizione e code | ricalcolati sulla selezione |
| Assenze del team (quadro, dettaglio, mesi) | persone coinvolte nei contratti |
- Export XLSX: foglio **Filtri**. Stampa: contratto nell'intestazione.
- **Fix**: Fatal error `array_map(): Argument #1 must be a valid callback` nel grafico giornaliero
  (v1.9.73, periodi > 92 giorni): media calcolata con `$pmSumG`.

## Report direzionale (`dir_report.php`, `app/DirModel.php`, `app/dir_report_print.php`)
- Quadro, agenti, grafici per dimensione, commesse, «da presidiare», perimetro agente: `commessa`.
- Andamento mensile: con filtro ricalcolato da `cm_intervention_reports` (stesse formule di
  `v_cm_dir_andamento`, che non ha la commessa).
- Stampa: filtri completati (contratto, aziende, ricerca, cliente). XLSX: foglio **Filtri**.

## Attività & Rendicontazione DGB (`dgb_activities.php`, `app/DgbModel.php`)
- Il filtro a scelta singola «Commessa» diventa il filtro globale multi-contratto (`a.id_contract`) in
  `whereActivities()` / `whereDetail()`: tabella, KPI, carico, ticket orfani, riepilogo orario, quadro del
  periodo, distribuzione temporale, matrice oraria.
- Assenze nella matrice oraria: incaricati che nel mese hanno lavorato sui contratti.
- Tab Anomalie: anomalie orarie dei giorni-operatore con attività sui contratti (riepilogo ricalcolato,
  contatore «alta» nella scheda); imputazioni errate sulla commessa errata o suggerita.
- Export XLSX (attività, distribuzione, matrice, anomalie): foglio **Filtri**; link del selettore mese
  ora conservano contratto, ricerca, orario, reperibilità.

## Fuori dal filtro (per natura)
Configurazione e anagrafiche: listino, tariffe, elenco team/code, profili incaricati, import & diff,
piani DGB senza attività, scheda tecnico complessiva (archivio intero, vista `v_cm_sd_scheda_tecnico`).

## QA (dump BI: 83.723 attività DGB, 70.549 righe Relazione IT, 3.666 ticket)
- WTS_3053 (contratto 114, 277 ticket collegati), 01/01–31/08/2026:
  SD ticket 2.700 → 21 (= conteggio diretto), moduli squadra 3 (= diretto), costi 567;
  DGB attività 19.797 → 578 (= diretto), ore 80.443,5 → 2.536; direzionale 1.118 → 1 commessa,
  andamento 10.178 h (= diretto); anomalie orarie 2.290 → 404.
- Scheda Paolo Baruchello: ticket presi 48 → 10.
- Link storico `contract=114` → selezione WTS_3053.
- Relazione IT: stessi risultati di v1.9.77 (19.398 / 1.751; `x' OR 1=1 --` → 0).
- Rendering senza errori: pagine, stampe, XLSX (SD, direzionale, DGB ×4).
- Tempi (senza snapshot): SD 6,4 s → 1,2 s filtrato; direzionale 1,3 → 0,2 s; DGB 2,5 → 1,4 s.
- `php -l` su tutti i file; migration RUN1/RUN2 err=0.
